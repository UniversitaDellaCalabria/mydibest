<?php

declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Assistenza\Didacticus\VociRepository;
use App\Eventi\EventoRepository;
use App\Forms\ServizioForms;
use App\Portale\ComunicazioniRepository;
use App\Portale\RicercaGlobale;

/**
 * La ricerca unica del portale e i moduli di MyForms: un modulo aperto compare solo a chi può compilarlo (profilo ammesso), mai a chi non lo è; i moduli
 * in bozza, chiusi o fuori periodo non compaiono per nessuno.
 */
final class RicercaModuliTest extends FormsBase
{
    protected function tabelle(): array
    {
        $d = \App\Core\Schema\Definizioni::tabelle();

        return array_merge(parent::tabelle(), [
            // le altre ricerche (eventi, notizie, guide, docenti, Didacticus) restano vuote: qui conta solo il gruppo dei moduli
            'personale_ateneo' => $d['personale_ateneo'],
            'comunicazioni' => $d['comunicazioni'],
            'didacticus_voci' => $d['didacticus_voci'],
            'pagine_eventi' => 'CREATE TABLE pagine_eventi (id INT PRIMARY KEY, titolo VARCHAR(100), slug VARCHAR(100), colore_primario VARCHAR(20), visibile TINYINT DEFAULT 1)',
            'eventi' => "CREATE TABLE eventi (id INT PRIMARY KEY, pagina_id INT, titolo VARCHAR(200) DEFAULT '', descrizione TEXT, luogo VARCHAR(200) DEFAULT '', archiviato TINYINT DEFAULT 0, tipo VARCHAR(20) DEFAULT 'evento')",
            'turni' => 'CREATE TABLE turni (id INT PRIMARY KEY, evento_id INT, data_turno DATE)',
        ]);
    }

    private function ricerca(): RicercaGlobale
    {
        return new RicercaGlobale(
            $this->db, new EventoRepository($this->db), new ComunicazioniRepository($this->db), new VociRepository($this->db), $this->servizio(ServizioForms::class)
        );
    }

    /** @return array<string, mixed> */
    private function utente(int $id): array
    {
        return $this->db->riga('SELECT * FROM utenti WHERE id = ?', [$id]);
    }

    /** Un modulo aperto a certi profili. @param list<string> $profili @param array<string, mixed> $opzioni */
    private function modulo(string $titolo, array $profili, array $opzioni = [], bool $aperto = true): int
    {
        $id = $this->form($titolo, [['etichetta' => 'Note', 'tipo' => 'text']], array_merge(['pubblico_json' => json_encode(['profili' => $profili])], $opzioni));
        if ($aperto) {
            $this->servizio(ServizioForms::class)->apri($id);
        }

        return $id;
    }

    /** I titoli trovati nel gruppo dei moduli (vuoto se il gruppo non c'è). @param list<array<string, mixed>> $gruppi @return list<string> */
    private function titoliModuli(array $gruppi): array
    {
        foreach ($gruppi as $g) {
            if ($g['chiave'] === 'moduli') {
                $t = array_map(static fn (array $v): string => $v['titolo'], $g['voci']);
                sort($t);

                return $t;
            }
        }

        return [];
    }

    public function testUnModuloAiSoliStudentiCompareSoloAgliStudenti(): void
    {
        $this->modulo('Questionario zoologia studenti', ['studenti']);
        $r = $this->ricerca();

        self::assertSame(['Questionario zoologia studenti'], $this->titoliModuli($r->cerca('zoologia', true, $this->utente(2))), 'studente');
        self::assertSame([], $this->titoliModuli($r->cerca('zoologia', true, $this->utente(3))), 'docente: non è per lui, nemmeno il titolo');
        self::assertSame([], $this->titoliModuli($r->cerca('zoologia', false, null)), 'senza accesso');
        self::assertSame([], $this->titoliModuli($r->cerca('zoologia', false)), 'chiamata come prima, senza utente');
        self::assertSame(['Questionario zoologia studenti'], $this->titoliModuli($r->cerca('zoologia', true, $this->utente(1))), 'gli amministratori passano sempre');
    }

    public function testUnModuloAperto_ATuttiCompareAncheSenzaAccesso(): void
    {
        $id = $this->modulo('Sondaggio ornitologia per tutti', ['anonimi']);
        $r = $this->ricerca();

        $g = $r->cerca('ornitologia', false, null);

        self::assertSame(['Sondaggio ornitologia per tutti'], $this->titoliModuli($g));
        $voce = array_values(array_filter($g, static fn (array $x): bool => $x['chiave'] === 'moduli'))[0]['voci'][0];
        $codice = (string) $this->db->valore('SELECT codice FROM forms WHERE id = ?', [$id]);
        self::assertSame('form.php?c=' . $codice, $voce['link'], 'porta al modulo');
        self::assertStringContainsString('MyForms', $voce['meta']);
    }

    public function testBozzeChiusiEFuoriPeriodoNonCompaionoPerNessuno(): void
    {
        $this->modulo('Alfa bozza fenicotteri', ['anonimi'], [], false);
        $chiuso = $this->modulo('Beta chiuso fenicotteri', ['anonimi']);
        $this->servizio(ServizioForms::class)->chiudi($chiuso);
        $this->modulo('Gamma scaduto fenicotteri', ['anonimi'], ['aperta_al' => '2026-11-16 18:00:00']);
        $this->modulo('Delta futuro fenicotteri', ['anonimi'], ['aperta_dal' => '2026-12-01 00:00:00']);
        $this->modulo('Epsilon pieno fenicotteri', ['anonimi'], ['max_risposte' => 1]);
        $pieno = (int) $this->db->valore("SELECT id FROM forms WHERE titolo LIKE 'Epsilon%'");
        $this->servizio(ServizioForms::class)->apri($pieno);
        $this->db->esegui("INSERT INTO forms_risposte (form_id, profilo, risposte_json, codice_ricevuta) VALUES (?, 'anonimi', '[]', 'x1')", [$pieno]);
        $this->modulo('Zeta aperto fenicotteri', ['anonimi']);
        $r = $this->ricerca();

        self::assertSame(['Zeta aperto fenicotteri'], $this->titoliModuli($r->cerca('fenicotteri', true, $this->utente(1))), 'nemmeno l\'amministratore vede ciò che non si può compilare');
    }

    public function testSiCercaNelTitoloENellaDescrizioneConTutteLeParole(): void
    {
        $id = $this->modulo('Iscrizione alla gita', ['registrati']);
        $this->db->esegui('UPDATE forms SET descrizione = ? WHERE id = ?', ['<p>Gita al <b>museo</b> di storia naturale</p>', $id]);
        $r = $this->ricerca();
        $luca = $this->utente(2);

        self::assertSame(['Iscrizione alla gita'], $this->titoliModuli($r->cerca('museo naturale', true, $luca)));
        self::assertSame(['Iscrizione alla gita'], $this->titoliModuli($r->cerca('GITA', true, $luca)));
        self::assertSame([], $this->titoliModuli($r->cerca('museo vulcano', true, $luca)), 'ogni parola deve comparire');
        self::assertSame([], $this->titoliModuli($r->cerca('gita', false, null)), 'riservato a chi ha fatto l\'accesso');
    }
}
