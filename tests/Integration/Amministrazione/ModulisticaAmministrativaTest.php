<?php

declare(strict_types=1);

namespace Tests\Integration\Amministrazione;

use App\Core\Schema\Definizioni;
use App\Core\Schema\Migrazioni;
use App\Didattica\ModuloRepository;
use App\Didattica\ServizioModuli;
use App\Didattica\ServizioPratiche;
use Tests\Integration\Didattica\DidatticaBase;

/**
 * La Modulistica amministrativa (passo AM2): ogni modulo è della didattica o dell'amministrazione e ognuno vede solo i suoi; il salvataggio scrive
 * l'ambito; un documento amministrativo non diventa mai un modulo online né apre una pratica; dalla scheda dell'Amministrazione non si toccano
 * i moduli della Didattica (e viceversa), anche cambiando l'id a mano. Usa le tabelle e il contenitore della Didattica (stesse colonne dello schema).
 */
final class ModulisticaAmministrativaTest extends DidatticaBase
{
    private const AMM = ModuloRepository::AMBITO_AMMINISTRAZIONE;
    private const DID = ModuloRepository::AMBITO_DIDATTICA;

    private function repo(): ModuloRepository
    {
        return $this->servizio(ModuloRepository::class);
    }

    /** Un documento della Modulistica amministrativa, salvato come fa la scheda del pannello. */
    private function documentoAmm(string $titolo, string $categoria = 'Missioni', array $altro = []): int
    {
        $e = $this->servizio(ServizioModuli::class)->salva(['titolo' => $titolo, 'categoria' => $categoria, 'attivo' => '1'] + $altro, [], self::AMM);
        $this->assertNull($e['errore'], 'il documento amministrativo si salva');

        return $e['id'];
    }

    public function testLoSchemaHaLaColonnaAmbitoCheLasciaLeRigheDiPrimaAllaDidattica(): void
    {
        $this->assertGreaterThanOrEqual(130, Migrazioni::VERSIONE);
        $def = Definizioni::colonne()['didattica_moduli']['ambito'] ?? '';
        $this->assertStringContainsString("VARCHAR(20) NOT NULL DEFAULT 'didattica'", $def, 'le righe che ci sono già restano della didattica (valore di partenza)');
        $this->assertStringContainsString('ADD INDEX', $def, 'con il suo indice');
        // una riga inserita come prima (senza ambito) è della didattica
        $id = $this->modulo('Modulo di sempre');
        $this->assertSame(self::DID, $this->db->valore('SELECT ambito FROM didattica_moduli WHERE id = ?', [$id]));
    }

    public function testOgniAmbitoVedeSoloIModuliSuoi(): void
    {
        $did = $this->modulo('Domanda di tesi');
        $this->db->esegui('UPDATE didattica_moduli SET attivo = 1, categoria = ?, tipo = ? WHERE id = ?', ['Lauree', 'online', $did]);
        $amm = $this->documentoAmm('Rimborso missione');
        $repo = $this->repo();

        // perId: di partenza la didattica (tutto il codice di prima vede solo la didattica); null = qualsiasi ambito
        $this->assertSame($did, (int) $repo->perId($did)['id']);
        $this->assertNull($repo->perId($amm), 'un documento amministrativo non si legge come modulo della didattica');
        $this->assertSame($amm, (int) $repo->perId($amm, self::AMM)['id']);
        $this->assertNull($repo->perId($did, self::AMM));
        $this->assertSame($amm, (int) $repo->perId($amm, null)['id']);
        $this->assertSame($did, (int) $repo->perId($did, null)['id']);
        $this->assertNull($this->servizio(ServizioModuli::class)->modulo($amm), 'modulo.php non trova un documento amministrativo come modulo online');
        $this->assertSame(self::AMM, $this->servizio(ServizioModuli::class)->modulo($amm, null)['ambito'], '…ma sa dire che esiste e di che ambito è');

        // elenchi del pannello e pagine pubbliche
        $this->assertSame(['Domanda di tesi'], array_column($repo->elenco(), 'titolo'));
        $this->assertSame(['Rimborso missione'], array_column($repo->elenco(self::AMM), 'titolo'));
        $this->assertSame(['Lauree'], array_keys($repo->pubblicatiPerCategoria()));
        $this->assertSame(['Missioni'], array_keys($repo->pubblicatiPerCategoria(self::AMM)));
        $this->assertSame([], $repo->pubblicatiPerCategoria('boh'), 'un ambito che non esiste è vuoto');

        // conIter: solo i moduli dell'ambito
        $this->db->esegui("UPDATE didattica_moduli SET iter_json = '[1]' WHERE id IN (?, ?)", [$did, $amm]);
        $this->assertCount(1, $repo->conIter());
        $this->assertCount(1, $repo->conIter(self::AMM));
    }

    public function testSalvaScriveLAmbitoESoloDocumenti(): void
    {
        $s = $this->servizio(ServizioModuli::class);
        $amm = $this->documentoAmm('Modello di richiesta ferie', 'Personale', ['link' => 'www.unical.it/ferie', 'ordine' => '4', 'descrizione' => '<p>Ciao <script>x</script><strong>mondo</strong></p>']);
        $r = $this->db->riga('SELECT * FROM didattica_moduli WHERE id = ?', [$amm]);
        $this->assertSame([self::AMM, 'documento', 'Personale', 'https://www.unical.it/ferie', 4, 1], [$r['ambito'], $r['tipo'], $r['categoria'], $r['link'], (int) $r['ordine'], (int) $r['attivo']]);
        $this->assertStringNotContainsString('<script', $r['descrizione']);
        $this->assertNull($r['campi_json'], 'niente campi di un modulo online');
        $this->assertNull($r['iter_json']);
        $this->assertNull($r['domanda_json']);
        $this->assertSame('tutti', $r['destinatari']);

        // lo stesso servizio con la didattica scrive 'didattica'
        $d = $s->salva(['titolo' => 'Modulo studenti', 'tipo' => 'online'], []);
        $this->assertSame([self::DID, 'online'], [$this->db->valore('SELECT ambito FROM didattica_moduli WHERE id = ?', [$d['id']]), $d['tipo']]);

        // modifica di un documento amministrativo: stesso id, ambito invariato, e i campi di un modulo online nel POST non entrano
        $e2 = $s->salva(['modulo_id' => (string) $amm, 'titolo' => 'Ferie (nuovo modello)', 'c_etichetta' => ['Intruso'], 'c_tipo' => ['text'], 'iter' => ['@cdl'], 'aperto_al' => '2026-12-31'], [], self::AMM);
        $this->assertSame([$amm, null], [$e2['id'], $e2['errore']]);
        $r = $this->db->riga('SELECT titolo, ambito, campi_json, iter_json, aperto_al FROM didattica_moduli WHERE id = ?', [$amm]);
        $this->assertSame(['Ferie (nuovo modello)', self::AMM, null, null, null], array_values($r));
        $this->assertSame('titolo', $s->salva(['titolo' => ' '], [], self::AMM)['errore']);
    }

    public function testLaModulisticaAmministrativaRifiutaIModuliOnline(): void
    {
        $s = $this->servizio(ServizioModuli::class);
        $prima = (int) $this->db->valore('SELECT COUNT(*) FROM didattica_moduli');
        $e = $s->salva(['titolo' => 'Rimborso online', 'tipo' => 'online'], [], self::AMM);
        $this->assertSame('online_non_ammesso', $e['errore']);
        $this->assertSame($prima, (int) $this->db->valore('SELECT COUNT(*) FROM didattica_moduli'), 'non si salva niente');
        // nemmeno trasformando un documento esistente in un modulo online
        $amm = $this->documentoAmm('Ferie');
        $e = $s->salva(['modulo_id' => (string) $amm, 'titolo' => 'Ferie', 'tipo' => 'online'], [], self::AMM);
        $this->assertSame('online_non_ammesso', $e['errore']);
        $this->assertSame('documento', $this->db->valore('SELECT tipo FROM didattica_moduli WHERE id = ?', [$amm]));
    }

    public function testDallaSchedaNonSiToccanoIModuliDellaDidattica(): void
    {
        $s = $this->servizio(ServizioModuli::class);
        $did = $this->modulo('Domanda di tesi');
        $amm = $this->documentoAmm('Rimborso missione');

        // dalla scheda dell'Amministrazione: l'id di un modulo della Didattica non si modifica né si elimina
        $e = $s->salva(['modulo_id' => (string) $did, 'titolo' => 'Manomesso'], [], self::AMM);
        $this->assertSame('non_trovato', $e['errore']);
        $this->assertSame('Domanda di tesi', $this->db->valore('SELECT titolo FROM didattica_moduli WHERE id = ?', [$did]));
        $this->assertSame(-1, $s->elimina($did, self::AMM));
        $this->assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM didattica_moduli WHERE id = ?', [$did]));

        // e dalla scheda della Didattica non si toccano i documenti amministrativi
        $e = $s->salva(['modulo_id' => (string) $amm, 'titolo' => 'Manomesso', 'tipo' => 'online'], []);
        $this->assertSame('non_trovato', $e['errore']);
        $this->assertSame('Rimborso missione', $this->db->valore('SELECT titolo FROM didattica_moduli WHERE id = ?', [$amm]));
        $this->assertSame(-1, $s->elimina($amm));
        $this->assertSame(0, $s->duplica($amm), 'nemmeno copiare');
        $this->assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM didattica_moduli WHERE id = ?', [$amm]));

        // anche il repository, da solo, non scrive né cancella oltre il suo ambito
        $this->repo()->salva($did, ['titolo' => 'Via il repository', 'categoria' => 'x', 'descrizione' => '', 'tipo' => 'documento', 'link' => '', 'campi_json' => null, 'verbale_json' => null, 'iter_json' => null,
            'destinatari' => 'tutti', 'email_ufficio' => '', 'attivo' => 1, 'ordine' => 0, 'aperto_dal' => null, 'aperto_al' => null, 'giorni_promemoria' => 7], self::AMM);
        $this->assertSame('Domanda di tesi', $this->db->valore('SELECT titolo FROM didattica_moduli WHERE id = ?', [$did]));
        $this->repo()->elimina($did, self::AMM);
        $this->assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM didattica_moduli WHERE id = ?', [$did]));
        $this->expectException(\InvalidArgumentException::class);
        $this->repo()->salva(0, ['titolo' => 'x', 'categoria' => '', 'descrizione' => '', 'tipo' => 'documento', 'link' => '', 'campi_json' => null, 'verbale_json' => null, 'iter_json' => null,
            'destinatari' => 'tutti', 'email_ufficio' => '', 'attivo' => 1, 'ordine' => 0, 'aperto_dal' => null, 'aperto_al' => null, 'giorni_promemoria' => 7], 'boh');
    }

    public function testEliminaUnDocumentoAmministrativoSenzaPratiche(): void
    {
        $s = $this->servizio(ServizioModuli::class);
        $amm = $this->documentoAmm('Da eliminare');
        $this->assertSame(0, $s->elimina($amm, self::AMM));
        $this->assertNull($this->db->valore('SELECT id FROM didattica_moduli WHERE id = ?', [$amm]));
        $this->assertSame(-1, $s->elimina($amm, self::AMM), 'già eliminato: non trovato');
    }

    public function testUnModuloAmministrativoNonApreMaiUnaPratica(): void
    {
        $amm = $this->documentoAmm('Rimborso missione');
        $m = $this->repo()->perId($amm, null);
        $this->assertNotNull($m);
        $p = $this->servizio(ServizioPratiche::class);
        $studente = ['id' => 2, 'nome' => 'Luca', 'cognome' => 'Rossi', 'email' => 'luca@x.it'];
        $this->assertSame(0, $p->crea($m, $studente, []), 'crea');
        $this->assertSame(0, $p->salvaBozza($m, $studente, []), 'salva una bozza');
        $this->assertFalse($p->inviaBozza(1, $m, $studente, []), 'invia una bozza');
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM pratiche'), 'nessuna pratica');
    }
}
