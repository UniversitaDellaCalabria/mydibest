<?php

declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Forms\FormRepository;
use App\Forms\RispostaRepository;
use App\Forms\ServizioForms;

/** Regole di MyForms: pubblico, periodo, numero massimo, una risposta per persona, modifica, anonimato, allegati senza accesso, gestori. */
final class ServizioFormsTest extends FormsBase
{
    private function utente(int $id): array
    {
        return $this->db->riga('SELECT * FROM utenti WHERE id = ?', [$id]);
    }

    public function testCampoCondizionaleNascostoNonEObbligatorio(): void
    {
        $id = $this->form('Dubbi', [
            ['etichetta' => 'Hai un dubbio?', 'tipo' => 'checkbox'],
            ['etichetta' => 'Spiega', 'tipo' => 'text', 'obbligatorio' => 1, 'cond' => ['campo' => 'Hai un dubbio?', 'op' => 'uguale', 'valore' => 'Sì']],
        ]);
        $servizio = $this->servizio(ServizioForms::class);
        $servizio->apri($id);
        $form = $this->servizio(FormRepository::class)->perId($id);

        $esito = $servizio->salvaRisposta($form, $this->utente(2), [], [], '1.2.3.4');
        self::assertTrue($esito['ok'], 'il campo nascosto dalla condizione non deve essere obbligatorio');

        $riga = $this->servizio(RispostaRepository::class)->perId($esito['id']);
        $risposte = json_decode((string) $riga['risposte_json'], true);
        self::assertTrue($risposte[1]['nascosto'] ?? false, 'il secondo campo è nascosto perché la condizione non è vera');
    }

    public function testCampoObbligatorioMancanteDaErrore(): void
    {
        $id = $this->form('Iscrizione', [['etichetta' => 'Nome completo', 'tipo' => 'text', 'obbligatorio' => 1]]);
        $servizio = $this->servizio(ServizioForms::class);
        $servizio->apri($id);
        $form = $this->servizio(FormRepository::class)->perId($id);

        $esito = $servizio->salvaRisposta($form, $this->utente(2), [], [], '1.2.3.4');
        self::assertFalse($esito['ok']);
        self::assertStringContainsString('obbligatorio', $esito['errori'][0]);
    }

    public function testUnaPerPersona(): void
    {
        $id = $this->form('Sondaggio', [['etichetta' => 'Voto', 'tipo' => 'number']], ['una_per_persona' => 1]);
        $servizio = $this->servizio(ServizioForms::class);
        $servizio->apri($id);
        $form = $this->servizio(FormRepository::class)->perId($id);
        $post = ['campo_c1' => '8'];

        $primo = $servizio->salvaRisposta($form, $this->utente(2), $post, [], '1.2.3.4');
        self::assertTrue($primo['ok']);

        $secondo = $servizio->salvaRisposta($form, $this->utente(2), $post, [], '1.2.3.4');
        self::assertFalse($secondo['ok']);
        self::assertStringContainsString('già risposto', $secondo['errori'][0]);

        // un'altra persona può sempre rispondere
        $terzo = $servizio->salvaRisposta($form, $this->utente(4), $post, [], '1.2.3.4');
        self::assertTrue($terzo['ok']);
    }

    public function testSenzaUnaPerPersonaSiPuoRispondereDiNuovo(): void
    {
        $id = $this->form('Richiesta', [['etichetta' => 'Note', 'tipo' => 'textarea']], ['una_per_persona' => 0]);
        $servizio = $this->servizio(ServizioForms::class);
        $servizio->apri($id);
        $form = $this->servizio(FormRepository::class)->perId($id);

        self::assertTrue($servizio->salvaRisposta($form, $this->utente(2), [], [], '1.2.3.4')['ok']);
        self::assertTrue($servizio->salvaRisposta($form, $this->utente(2), [], [], '1.2.3.4')['ok'], 'con una_per_persona spento si può rispondere più volte');
    }

    public function testModificaConsentitaENegata(): void
    {
        $id = $this->form('Adesione', [['etichetta' => 'Note', 'tipo' => 'textarea']], ['modificabile' => 1, 'una_per_persona' => 0]);
        $servizio = $this->servizio(ServizioForms::class);
        $servizio->apri($id);
        $form = $this->servizio(FormRepository::class)->perId($id);
        $esito = $servizio->salvaRisposta($form, $this->utente(2), ['campo_c1' => 'prima versione'], [], '1.2.3.4');
        $risposte = $this->servizio(RispostaRepository::class);
        $risposta = $risposte->perId($esito['id']);

        // la stessa persona modifica
        $mod = $servizio->modificaRisposta($form, $risposta, $this->utente(2), ['campo_c1' => 'seconda versione'], []);
        self::assertTrue($mod['ok']);
        $dati = json_decode((string) $risposte->perId($esito['id'])['risposte_json'], true);
        self::assertSame('seconda versione', $dati[0]['valore']);

        // un'altra persona non può modificare la risposta di Luca
        $negata = $servizio->modificaRisposta($form, $risposta, $this->utente(4), ['campo_c1' => 'intrusione'], []);
        self::assertFalse($negata['ok']);
        self::assertStringContainsString('non è tua', $negata['errori'][0]);

        // un form non modificabile non si lascia correggere
        $id2 = $this->form('Fissa', [['etichetta' => 'Note', 'tipo' => 'textarea']], ['modificabile' => 0, 'una_per_persona' => 0]);
        $servizio->apri($id2);
        $form2 = $this->servizio(FormRepository::class)->perId($id2);
        $esito2 = $servizio->salvaRisposta($form2, $this->utente(2), ['campo_c1' => 'x'], [], '1.2.3.4');
        $risposta2 = $risposte->perId($esito2['id']);
        $vietata = $servizio->modificaRisposta($form2, $risposta2, $this->utente(2), ['campo_c1' => 'y'], []);
        self::assertFalse($vietata['ok']);
        self::assertStringContainsString('non permette', $vietata['errori'][0]);
    }

    public function testPeriodoApertura(): void
    {
        $servizio = $this->servizio(ServizioForms::class);
        $forms = $this->servizio(FormRepository::class);
        $id = $this->form('Finestra', [['etichetta' => 'Note', 'tipo' => 'text']]);
        $servizio->apri($id);

        // non ancora aperto (l'orologio di prova è fermo al 2026-11-17 10:00)
        $servizio->modifica($id, $this->datiModifica($forms->perId($id), ['aperta_dal' => '2026-12-01 00:00:00']));
        [$ok, $motivo] = $servizio->puoRispondere($forms->perId($id), $this->utente(2));
        self::assertFalse($ok);
        self::assertStringContainsString('si apre il', $motivo);

        // già chiuso
        $servizio->modifica($id, $this->datiModifica($forms->perId($id), ['aperta_dal' => null, 'aperta_al' => '2026-01-01 00:00:00']));
        [$ok, $motivo] = $servizio->puoRispondere($forms->perId($id), $this->utente(2));
        self::assertFalse($ok);
        self::assertStringContainsString('si è chiuso il', $motivo);

        // dentro la finestra
        $servizio->modifica($id, $this->datiModifica($forms->perId($id), ['aperta_dal' => '2026-11-01 00:00:00', 'aperta_al' => '2026-12-01 00:00:00']));
        [$ok] = $servizio->puoRispondere($forms->perId($id), $this->utente(2));
        self::assertTrue($ok);
    }

    public function testNumeroMassimoRisposte(): void
    {
        $id = $this->form('Posti limitati', [['etichetta' => 'Note', 'tipo' => 'text']], ['max_risposte' => 1, 'una_per_persona' => 0]);
        $servizio = $this->servizio(ServizioForms::class);
        $servizio->apri($id);
        $form = $this->servizio(FormRepository::class)->perId($id);

        self::assertTrue($servizio->salvaRisposta($form, $this->utente(2), [], [], '1.2.3.4')['ok']);
        $form = $this->servizio(FormRepository::class)->perId($id);
        $esito = $servizio->salvaRisposta($form, $this->utente(4), [], [], '1.2.3.4');
        self::assertFalse($esito['ok']);
        self::assertStringContainsString('numero massimo', $esito['errori'][0]);
    }

    public function testFormAnonimoNonSalvaUtenteEdEmail(): void
    {
        $id = $this->form('Segnalazione anonima', [['etichetta' => 'Testo', 'tipo' => 'textarea']], ['anonima' => 1, 'una_per_persona' => 0]);
        $servizio = $this->servizio(ServizioForms::class);
        $servizio->apri($id);
        $form = $this->servizio(FormRepository::class)->perId($id);

        $esito = $servizio->salvaRisposta($form, $this->utente(2), ['campo_c1' => 'segnalo questo'], [], '1.2.3.4');
        self::assertTrue($esito['ok']);
        $riga = $this->servizio(RispostaRepository::class)->perId($esito['id']);
        self::assertNull($riga['utente_id']);
        self::assertNull($riga['email']);
        self::assertNotNull($riga['utente_impronta'], "chi ha fatto l'accesso lascia comunque l'impronta, anche in un form anonimo");
    }

    public function testAnonimoConUnaPerPersonaRifiutaLaSecondaRisposta(): void
    {
        $id = $this->form('Sondaggio anonimo', [['etichetta' => 'Voto', 'tipo' => 'number']], ['anonima' => 1, 'una_per_persona' => 1]);
        $servizio = $this->servizio(ServizioForms::class);
        $servizio->apri($id);
        $form = $this->servizio(FormRepository::class)->perId($id);

        $primo = $servizio->salvaRisposta($form, $this->utente(2), ['campo_c1' => '7'], [], '1.2.3.4');
        self::assertTrue($primo['ok']);

        $secondo = $servizio->salvaRisposta($form, $this->utente(2), ['campo_c1' => '9'], [], '1.2.3.4');
        self::assertFalse($secondo['ok'], "senza utente_id l'identità arriva solo dall'impronta: deve bastare a bloccare la seconda risposta");
        self::assertStringContainsString('già risposto', $secondo['errori'][0]);

        // un'altra persona, nello stesso form anonimo, può sempre rispondere
        $terzo = $servizio->salvaRisposta($form, $this->utente(4), ['campo_c1' => '5'], [], '1.2.3.4');
        self::assertTrue($terzo['ok']);
    }

    public function testModificaInFormAnonimo(): void
    {
        $id = $this->form('Segnalazione modificabile', [['etichetta' => 'Testo', 'tipo' => 'textarea']], ['anonima' => 1, 'modificabile' => 1, 'una_per_persona' => 0]);
        $servizio = $this->servizio(ServizioForms::class);
        $servizio->apri($id);
        $form = $this->servizio(FormRepository::class)->perId($id);
        $esito = $servizio->salvaRisposta($form, $this->utente(2), ['campo_c1' => 'prima versione'], [], '1.2.3.4');
        self::assertTrue($esito['ok']);
        $risposte = $this->servizio(RispostaRepository::class);
        $risposta = $risposte->perId($esito['id']);

        // la stessa persona modifica, riconosciuta dall'impronta (non c'è utente_id da confrontare)
        $mod = $servizio->modificaRisposta($form, $risposta, $this->utente(2), ['campo_c1' => 'seconda versione'], []);
        self::assertTrue($mod['ok']);
        $dati = json_decode((string) $risposte->perId($esito['id'])['risposte_json'], true);
        self::assertSame('seconda versione', $dati[0]['valore']);

        // un'altra persona non può modificarla, anche se il form è anonimo
        $negata = $servizio->modificaRisposta($form, $risposta, $this->utente(4), ['campo_c1' => 'intrusione'], []);
        self::assertFalse($negata['ok']);
        self::assertStringContainsString('non è tua', $negata['errori'][0]);
    }

    public function testModificaSenzaRicaricareLAllegatoRestaQuelloVecchio(): void
    {
        $id = $this->form('Documento', [['etichetta' => 'Certificato', 'tipo' => 'file', 'obbligatorio' => 1]], ['modificabile' => 1, 'una_per_persona' => 0]);
        $servizio = $this->servizio(ServizioForms::class);
        $servizio->apri($id);
        $form = $this->servizio(FormRepository::class)->perId($id);
        $risposte = $this->servizio(RispostaRepository::class);
        // Una risposta già inviata (con l'allegato già caricato in precedenza): creata qui direttamente, come farebbe un invio vero
        $vecchie = [['etichetta' => 'Certificato', 'tipo' => 'file', 'valore' => 'vecchio.pdf', 'file' => 'uploads/forms/' . $id . '/abc123.pdf', 'nome_file' => 'vecchio.pdf']];
        $impronta = hash('sha256', $id . '|2|prova-chiave-di-almeno-16-caratteri');
        $rid = $risposte->inserisci($id, 2, 'luca@x.it', 'studenti', (string) json_encode($vecchie, JSON_UNESCAPED_UNICODE), bin2hex(random_bytes(16)), null, $impronta);
        $risposta = $risposte->perId($rid);

        // si modifica senza ricaricare il file: non deve dare «campo obbligatorio» e deve restare l'allegato di prima
        $esito = $servizio->modificaRisposta($form, $risposta, $this->utente(2), [], []);
        self::assertTrue($esito['ok'], $esito['errori'][0] ?? '');
        $dati = json_decode((string) $risposte->perId($rid)['risposte_json'], true);
        self::assertSame('vecchio.pdf', $dati[0]['valore']);
        self::assertSame('uploads/forms/' . $id . '/abc123.pdf', $dati[0]['file']);
    }

    public function testCampiFileVietatiSenzaAccesso(): void
    {
        $id = $this->form('Allegato', [['etichetta' => 'Carica il documento', 'tipo' => 'file']], ['pubblico_json' => json_encode(['profili' => ['anonimi']])]);
        $servizio = $this->servizio(ServizioForms::class);
        $servizio->apri($id);
        $form = $this->servizio(FormRepository::class)->perId($id);

        $esito = $servizio->salvaRisposta($form, null, [], [], '1.2.3.4');
        self::assertFalse($esito['ok']);
        self::assertStringContainsString('accesso', $esito['errori'][0]);
    }

    public function testPubblicoSoloStudentiRifiutaDocente(): void
    {
        $id = $this->form('Solo studenti', [['etichetta' => 'Note', 'tipo' => 'text']], ['pubblico_json' => json_encode(['profili' => ['studenti']])]);
        $servizio = $this->servizio(ServizioForms::class);
        $servizio->apri($id);
        $form = $this->servizio(FormRepository::class)->perId($id);

        [$okStudente] = $servizio->puoRispondere($form, $this->utente(2));
        self::assertTrue($okStudente);
        [$okDocente, $motivo] = $servizio->puoRispondere($form, $this->utente(3));
        self::assertFalse($okDocente);
        self::assertStringContainsString('profilo', $motivo);
    }

    public function testPuoGestire(): void
    {
        $id = $this->form('Gestione', [['etichetta' => 'Note', 'tipo' => 'text']], ['proprietario_id' => 2]);
        $servizio = $this->servizio(ServizioForms::class);
        $form = $this->servizio(FormRepository::class)->perId($id);

        self::assertTrue($servizio->puoGestire($form, $this->utente(1)), 'amministratore');
        self::assertTrue($servizio->puoGestire($form, $this->utente(2)), 'proprietario');
        self::assertFalse($servizio->puoGestire($form, $this->utente(4)), 'nessun ruolo sul form');

        $servizio->aggiungiGestore($id, 4);
        self::assertTrue($servizio->puoGestire($this->servizio(FormRepository::class)->perId($id), $this->utente(4)), 'co-gestore');

        $servizio->togliGestore($id, 4);
        self::assertFalse($servizio->puoGestire($this->servizio(FormRepository::class)->perId($id), $this->utente(4)));
    }

    public function testHaGestiti(): void
    {
        $forms = $this->servizio(FormRepository::class);
        $servizio = $this->servizio(ServizioForms::class);
        self::assertFalse($forms->haGestiti(4), 'Paolo non è ancora co-gestore di niente');

        $id = $this->form('Con co-gestore', [['etichetta' => 'Note', 'tipo' => 'text']], ['proprietario_id' => 2]);
        self::assertFalse($forms->haGestiti(4), 'Paolo non è il proprietario: non basta per haGestiti()');

        $servizio->aggiungiGestore($id, 4);
        self::assertTrue($forms->haGestiti(4));

        $servizio->togliGestore($id, 4);
        self::assertFalse($forms->haGestiti(4));
    }

    public function testPuoEliminare(): void
    {
        $id = $this->form('Da eliminare', [['etichetta' => 'Note', 'tipo' => 'text']], ['proprietario_id' => 2]);
        $servizio = $this->servizio(ServizioForms::class);
        $form = $this->servizio(FormRepository::class)->perId($id);

        self::assertTrue($servizio->puoEliminare($form, $this->utente(1)), 'amministratore');
        self::assertTrue($servizio->puoEliminare($form, $this->utente(2)), 'proprietario');
        self::assertFalse($servizio->puoEliminare($form, $this->utente(4)), 'co-gestore senza altro ruolo non elimina');

        $servizio->aggiungiGestore($id, 4);
        self::assertFalse($servizio->puoEliminare($this->servizio(FormRepository::class)->perId($id), $this->utente(4)), 'un co-gestore non elimina il form');
    }

    public function testVisibiliA(): void
    {
        $servizio = $this->servizio(ServizioForms::class);
        $id1 = $this->form('Di Luca', [['etichetta' => 'Note', 'tipo' => 'text']], ['proprietario_id' => 2]);
        $id2 = $this->form('Di Paolo', [['etichetta' => 'Note', 'tipo' => 'text']], ['proprietario_id' => 4]);

        $perAdmin = $servizio->visibiliA($this->utente(1));
        self::assertCount(2, $perAdmin, "l'amministratore vede tutti i form");

        $perLuca = $servizio->visibiliA($this->utente(2));
        self::assertCount(1, $perLuca);
        self::assertSame($id1, (int) $perLuca[0]['id']);

        $servizio->aggiungiGestore($id2, 2);
        $perLuca = $servizio->visibiliA($this->utente(2));
        self::assertCount(2, $perLuca, 'Luca vede anche i form di cui è co-gestore');
    }

    public function testAggiungiGestoreDaAnagrafe(): void
    {
        $id = $this->form('Gestione anagrafe', [['etichetta' => 'Note', 'tipo' => 'text']], ['proprietario_id' => 1]);
        $servizio = $this->servizio(ServizioForms::class);

        self::assertStringContainsString('non trovata', (string) $servizio->aggiungiGestoreDaAnagrafe($id, 'inesistente'));

        $this->db->esegui("INSERT INTO personale_ateneo (id, nome, cognome, email, docente, attivo) VALUES ('p1', 'Sara', 'Nuova', 'sara.nuova@x.it', 1, 1)");
        $err = $servizio->aggiungiGestoreDaAnagrafe($id, 'p1');
        self::assertSame("Questa persona non ha ancora fatto l'accesso al portale: deve accedere una prima volta, poi la aggiungi come co-gestore.", $err);

        // Sara fa il primo accesso: ora c'è un utente con la sua stessa email
        $this->db->esegui("INSERT INTO utenti (id, nome, cognome, email, ruolo_id) VALUES (5, 'Sara', 'Nuova', 'sara.nuova@x.it', 4)");
        $err2 = $servizio->aggiungiGestoreDaAnagrafe($id, 'p1');
        self::assertNull($err2);
        $form = $this->servizio(FormRepository::class)->perId($id);
        self::assertTrue($servizio->puoGestire($form, $this->utente(5)), "l'utente con la stessa email dell'anagrafe diventa co-gestore");
    }

    public function testDuplicaECreaNuovoCodiceSenzaRisposte(): void
    {
        $id = $this->form('Originale', [['etichetta' => 'Note', 'tipo' => 'text']]);
        $servizio = $this->servizio(ServizioForms::class);
        $servizio->apri($id);
        $form = $this->servizio(FormRepository::class)->perId($id);
        $servizio->salvaRisposta($form, $this->utente(2), [], [], '1.2.3.4');

        $copiaId = $servizio->duplica($id);
        $copia = $this->servizio(FormRepository::class)->perId($copiaId);
        self::assertSame('bozza', $copia['stato']);
        self::assertNotSame($form['codice'], $copia['codice']);
        self::assertSame(0, $this->servizio(RispostaRepository::class)->conta($copiaId));
    }

    public function testEliminaRimuoveRisposteEGestori(): void
    {
        $id = $this->form('Da eliminare', [['etichetta' => 'Note', 'tipo' => 'text']]);
        $servizio = $this->servizio(ServizioForms::class);
        $servizio->apri($id);
        $servizio->aggiungiGestore($id, 4);
        $form = $this->servizio(FormRepository::class)->perId($id);
        $servizio->salvaRisposta($form, $this->utente(2), [], [], '1.2.3.4');

        $servizio->elimina($id);
        self::assertNull($this->servizio(FormRepository::class)->perId($id));
        self::assertSame(0, $this->servizio(RispostaRepository::class)->conta($id));
        self::assertSame([], $this->servizio(FormRepository::class)->gestori($id));
    }
}
